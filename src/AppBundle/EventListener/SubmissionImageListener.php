<?php

namespace Raddit\AppBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Embed\Embed;
use Embed\Exceptions\EmbedException;
use League\Flysystem\FileExistsException;
use League\Flysystem\FilesystemInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Raddit\AppBundle\Entity\Submission;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Download related image after submission.
 */
final class SubmissionImageListener implements LoggerAwareInterface {
    use LoggerAwareTrait;

    const QUEUE_KEY = 'submission_thumbnail_queue';

    /**
     * @var EntityManagerInterface
     */
    private $manager;

    /**
     * @var FilesystemInterface
     */
    private $filesystem;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    public function __construct(
        EntityManagerInterface $manager,
        FilesystemInterface $filesystem,
        RequestStack $requestStack,
        ValidatorInterface $validator
    ) {
        $this->manager = $manager;
        $this->filesystem = $filesystem;
        $this->requestStack = $requestStack;
        $this->validator = $validator;

        $this->setLogger(new NullLogger());
    }

    /**
     * Stick every submission with a URL in a queue.
     *
     * @param LifecycleEventArgs $args
     */
    public function postPersist(LifecycleEventArgs $args) {
        $request = $this->requestStack->getMasterRequest();
        $entity = $args->getEntity();

        if (!$request || !$entity instanceof Submission || !$entity->getUrl() || $entity->getImage()) {
            return;
        }

        $queue = $request->attributes->get(self::QUEUE_KEY, []);
        $queue[] = $entity;

        $request->attributes->set(self::QUEUE_KEY, $queue);
    }

    /**
     * Loop through the queue at the end of the request and download the images.
     *
     * @param KernelEvent $event
     */
    public function onKernelTerminate(KernelEvent $event) {
        if ($event->getRequestType() !== HttpKernelInterface::MASTER_REQUEST) {
            return;
        }

        $queue = $event->getRequest()->attributes->get(self::QUEUE_KEY, []);

        if (!$queue) {
            return;
        }

        /** @var Submission $submission */
        foreach ($queue as $submission) {
            try {
                $embed = Embed::create($submission->getUrl());
                $imageUrl = $embed->getImage();

                if ($imageUrl) {
                    $submission->setImage($this->getFilename($imageUrl));
                }
            } catch (EmbedException $e) {
                $this->logger->info($e->getMessage());
            }
        }

        $this->manager->flush();
    }

    /**
     * Download, store, and rename the image.
     *
     * @param string $imageUrl
     *
     * @return string|null the final file name, or null if the download failed
     */
    private function getFilename(string $imageUrl) {
        error_clear_last();

        try {
            // fixme: don't create temporary files
            $tempFile = @tempnam(sys_get_temp_dir(), 'raddit');
            $fh = @fopen($tempFile, 'wb+');

            if (!$fh) {
                $this->logger->warning('Could not open file for writing', [
                    'error' => error_get_last(),
                ]);

                return null;
            }

            // todo: refactor to use guzzle or something
            $ch = curl_init($imageUrl);
            curl_setopt($ch, CURLOPT_FILE, $fh);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $success = curl_exec($ch) && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) == 200;

            if (!$success) {
                $this->logger->info('Bad HTTP response', [
                    'curl' => curl_getinfo($ch),
                ]);

                return null;
            }

            $imageConstraint = new Image(['detectCorrupted' => true]);

            $violations = $this->validator->validate($tempFile, $imageConstraint);

            if (count($violations) > 0) {
                /** @var ConstraintViolationInterface $violation */
                foreach ($violations as $violation) {
                    $message = $violation->getMessageTemplate();
                    $params = $violation->getParameters();

                    $this->logger->info($message, $params);
                }

                return null;
            }

            $mimeType = MimeTypeGuesser::getInstance()->guess($tempFile);
            $ext = ExtensionGuesser::getInstance()->guess($mimeType);

            $filename = sprintf('%s.%s', hash_file('sha256', $tempFile), $ext);

            try {
                $success = $this->filesystem->writeStream($filename, $fh);
            } catch (FileExistsException $e) {
                $success = true;
            }

            return $success ? $filename : null;
        } finally {
            if (isset($ch)) {
                @curl_close($ch);
            }

            @fclose($fh);
            @unlink($tempFile);
        }
    }
}
