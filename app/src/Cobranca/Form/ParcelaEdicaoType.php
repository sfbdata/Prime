<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\ParcelaEdicaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Uma linha do editor de parcelas do acordo (Ajuste 7, Fatia 4). `obrigacaoId` oculto identifica a
 * parcela existente; vazio = linha nova. Valor em CENTAVOS via CentavosType.
 */
final class ParcelaEdicaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('obrigacaoId', HiddenType::class, ['required' => false])
            ->add('descricao', TextType::class, [
                'label' => 'Descrição',
                'attr' => ['maxlength' => 255, 'class' => 'form-control form-control-sm'],
            ])
            ->add('valor', CentavosType::class, [
                'label' => 'Valor',
                'attr' => ['class' => 'form-control form-control-sm', 'inputmode' => 'decimal', 'placeholder' => '0,00'],
            ])
            ->add('vencimento', DateType::class, [
                'label' => 'Vencimento',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control form-control-sm'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ParcelaEdicaoInput::class]);
    }
}
