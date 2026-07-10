<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\ResolverRevisaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Resolver uma revisão de pessoa cobrada pendente. `revisaoId` vem da rota. Renderizado num modal
 * reutilizável (JS injeta a URL da revisão da linha).
 */
final class ResolverRevisaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('resolucao', TextareaType::class, [
                'label' => 'Como foi resolvida',
                'attr' => ['class' => 'form-control', 'rows' => 2, 'placeholder' => 'Ex.: confirmado que é a pessoa correta'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ResolverRevisaoInput::class,
        ]);
    }
}
