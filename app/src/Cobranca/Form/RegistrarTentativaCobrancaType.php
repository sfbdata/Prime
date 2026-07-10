<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Registrar uma tentativa de cobrança (contato) no histórico do Caso. `casoId` vem da rota. Todos os
 * campos são opcionais além do caso; a única escrita é o evento de histórico.
 */
final class RegistrarTentativaCobrancaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('valorSolicitado', CentavosType::class, [
                'label' => 'Valor solicitado (opcional, R$)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('novoPrazo', DateType::class, [
                'label' => 'Novo prazo combinado (opcional)',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('observacao', TextareaType::class, [
                'label' => 'Observação (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrarTentativaCobrancaInput::class,
        ]);
    }
}
