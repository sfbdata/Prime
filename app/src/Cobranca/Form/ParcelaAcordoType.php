<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\ParcelaAcordoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Uma parcela do acordo (sub-form da coleção em AcordoCriarType). Dinheiro em CentavosType.
 */
final class ParcelaAcordoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('descricao', TextType::class, [
                'label' => 'Descrição',
                'attr' => ['class' => 'form-control form-control-sm', 'placeholder' => 'Ex.: Parcela 1/3', 'maxlength' => 255],
            ])
            ->add('valor', CentavosType::class, [
                'label' => 'Valor (R$)',
                'attr' => ['class' => 'form-control form-control-sm'],
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
        $resolver->setDefaults([
            'data_class' => ParcelaAcordoInput::class,
        ]);
    }
}
