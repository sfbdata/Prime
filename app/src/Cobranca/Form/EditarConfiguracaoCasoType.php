<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\EditarConfiguracaoCasoInput;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Editar os HONORÁRIOS de um Caso já existente (Ajuste 2, Fatia A): forma, percentual, base e
 * carência. `casoId` NÃO é campo — vem da rota e é preenchido pelo controller, que também pré-carrega
 * o DTO com os valores atuais do caso para o modal já abrir preenchido. Só honorários (D-A2-2).
 * Validação nos #[Assert] do DTO.
 *
 * `baseHonorarios` e `carenciaHonorariosDias` são OVERRIDES nullable do caso (null = herda a
 * carteira), por isso `required => false` + placeholder de "herda". `formaHonorarios` é NOT NULL na
 * entidade, então sempre concreto (sem placeholder), como no form da carteira.
 */
final class EditarConfiguracaoCasoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('formaHonorarios', EnumType::class, [
                'label' => 'Forma de honorários',
                'class' => FormaHonorarios::class,
                'choice_label' => static fn (FormaHonorarios $f): string => $f->label(),
                'attr' => ['class' => 'form-select', 'data-forma-honorarios' => '1'],
            ])
            ->add('percentualHonorarios', PercentualType::class, [
                'label' => 'Percentual de honorários (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            // Override do caso: o placeholder deixa escolher "herda a carteira" (null); sem
            // `empty_data`, pois null é o valor NEUTRO e significativo aqui (não reseta config salva).
            ->add('baseHonorarios', EnumType::class, [
                'label' => 'Base dos honorários',
                'class' => BaseEncargo::class,
                'choice_label' => static fn (BaseEncargo $b): string => $b->label(),
                'required' => false,
                'placeholder' => 'Herda a carteira',
                'attr' => ['class' => 'form-select'],
            ])
            // Vazio = null = herda a carência da carteira (não vira 0 por acidente).
            ->add('carenciaHonorariosDias', IntegerType::class, [
                'label' => 'Carência dos honorários (dias)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => 'Vazio = usa a carência da carteira',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditarConfiguracaoCasoInput::class,
        ]);
    }
}
