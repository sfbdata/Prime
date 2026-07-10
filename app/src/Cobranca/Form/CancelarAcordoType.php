<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\CancelarAcordoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Cancelar um acordo ativo (erro de lançamento; restaura as obrigações originais). `acordoId` vem da
 * rota. Motivo opcional. Modal reutilizável (JS injeta a URL do acordo da linha).
 */
final class CancelarAcordoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('motivo', TextareaType::class, [
            'label' => 'Motivo (opcional)',
            'required' => false,
            'attr' => ['class' => 'form-control', 'rows' => 2, 'maxlength' => 255],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CancelarAcordoInput::class,
        ]);
    }
}
