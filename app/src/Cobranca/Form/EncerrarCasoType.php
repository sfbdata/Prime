<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\EncerrarCasoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Encerrar o Caso manualmente. `casoId` vem da rota (preenchido pelo controller). O encerramento só é
 * aceito com saldo exigível 0 (invariável 17) — a regra vive no UseCase; aqui só a observação opcional.
 */
final class EncerrarCasoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('observacao', TextareaType::class, [
                'label' => 'Observação (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 2, 'maxlength' => 255],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EncerrarCasoInput::class,
        ]);
    }
}
