<?php

declare(strict_types=1);

namespace App\Djen\Form;

use App\Djen\DTO\OabMonitoradaInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OabMonitoradaType extends AbstractType
{
    private const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('numero', TextType::class, [
                'label' => 'Número da OAB',
                'required' => true,
                'attr' => ['class' => 'pp-campo pp-campo--form', 'placeholder' => 'Somente números', 'inputmode' => 'numeric'],
            ])
            ->add('uf', ChoiceType::class, [
                'label' => 'UF',
                'required' => true,
                'choices' => array_combine(self::UFS, self::UFS),
                'placeholder' => 'UF',
                'attr' => ['class' => 'pp-campo pp-campo--form'],
            ])
            ->add('apelido', TextType::class, [
                'label' => 'Apelido (opcional)',
                'required' => false,
                'attr' => ['class' => 'pp-campo pp-campo--form', 'placeholder' => 'Ex.: Dra. Fulana'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OabMonitoradaInput::class,
        ]);
    }
}
