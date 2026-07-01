<?php

namespace App\Form;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Pasta\Entity\Pasta;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
            ->add('situacao', ChoiceType::class, [
                'label' => 'Situação',
                'required' => true,
                'choices' => [
                    'Ativo'     => 'ativo',
                    'Arquivado' => 'arquivado',
                ],
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
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Pasta::class,
        ]);
    }
}
