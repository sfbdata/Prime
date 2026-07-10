<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\GerarRevisaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Gerar uma revisão de pessoa cobrada (alerta que só cessa após resolução). `casoId` vem da rota.
 */
final class GerarRevisaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('motivo', TextareaType::class, [
                'label' => 'Motivo da revisão',
                'attr' => ['class' => 'form-control', 'rows' => 2, 'maxlength' => 255, 'placeholder' => 'Ex.: possível homônimo / dados divergentes'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GerarRevisaoInput::class,
        ]);
    }
}
