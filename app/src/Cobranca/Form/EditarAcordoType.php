<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\EditarAcordoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Editor do acordo (Ajuste 7, Fatia 4): total negociado + o conjunto COMPLETO de parcelas (a entrada
 * é uma linha como as outras — D8). `acordoId` vem da rota. As parcelas com pagamento alocado são
 * renderizadas travadas no template e reenviadas intactas; o UseCase revalida (INV-C).
 */
final class EditarAcordoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('valorTotalNegociado', CentavosType::class, [
                'label' => 'Total negociado',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => '0,00', 'data-acordo-total' => ''],
            ])
            ->add('parcelas', CollectionType::class, [
                'entry_type' => ParcelaEdicaoType::class,
                'label' => 'Parcelas',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'entry_options' => ['label' => false],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EditarAcordoInput::class]);
    }
}
