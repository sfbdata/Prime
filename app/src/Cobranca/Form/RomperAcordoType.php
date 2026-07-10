<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\RomperAcordoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Romper um acordo ativo (restaura as obrigações originais). `acordoId` vem da rota. Motivo
 * obrigatório. Modal reutilizável (JS injeta a URL do acordo da linha).
 */
final class RomperAcordoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('motivo', TextareaType::class, [
            'label' => 'Motivo do rompimento',
            'attr' => ['class' => 'form-control', 'rows' => 2, 'placeholder' => 'Ex.: parcelas em atraso'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RomperAcordoInput::class,
        ]);
    }
}
