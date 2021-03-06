<?php

namespace App\Form;

use App\Form\Model\ForumCategoryData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ForumCategoryType extends AbstractType {
    public function buildForm(FormBuilderInterface $builder, array $options) {
        $builder
            ->add('name', TextType::class, [
                'label' => 'label.name',
            ])
            ->add('title', TextType::class, [
                'label' => 'label.title',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'label.description',
            ])
            ->add('sidebar', TextareaType::class, [
                'label' => 'label.sidebar',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver) {
        $resolver->setDefaults([
            'data_class' => ForumCategoryData::class,
        ]);
    }
}
