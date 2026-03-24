<?php

namespace App\Form;

use App\Entity\Cliente\Cliente;
use App\Entity\Cliente\ClientePF;
use App\Entity\Pasta\Pasta;
use App\Entity\Processo\Processo;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PastaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nup', TextType::class, [
                'label' => 'NUP',
                'required' => true,
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'required' => true,
                'choices' => [
                    'Ativo' => 'ativo',
                    'Arquivado' => 'arquivado',
                ],
            ])
            ->add('dataAbertura', DateType::class, [
                'label' => 'Data de Abertura',
                'widget' => 'single_text',
                'required' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('descricao', TextareaType::class, [
                'label' => 'Descrição',
                'required' => false,
            ])
            ->add('processo', EntityType::class, [
                'class' => Processo::class,
                'label' => 'Processo',
                'required' => false,
                'choice_label' => 'numeroProcesso',
            ])
            ->add('clientes', EntityType::class, [
                'class' => Cliente::class,
                'label' => 'Clientes',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choice_label' => function (Cliente $cliente): string {
                    if ($cliente instanceof ClientePF) {
                        return $cliente->getNomeCompleto();
                    }

                    return $cliente->getRazaoSocial() ?? $cliente->getEmail();
                },
            ])
            ->add('partesContrarias', CollectionType::class, [
                'label' => 'Partes Contrárias',
                'entry_type' => ParteContrariaType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Pasta::class,
        ]);
    }
}
