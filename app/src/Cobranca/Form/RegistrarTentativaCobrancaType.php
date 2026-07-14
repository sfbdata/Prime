<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use App\Cobranca\Enum\CanalContato;
use App\Cobranca\Enum\ResultadoContato;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Registrar um CONTATO de cobrança no histórico do Caso. `casoId` vem da rota; `dataContato` é
 * pré-preenchida com "agora" pelo controller. A única escrita é o evento de histórico.
 */
final class RegistrarTentativaCobrancaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dataContato', DateTimeType::class, [
                'label' => 'Data e hora do contato',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('canal', EnumType::class, [
                'class' => CanalContato::class,
                'label' => 'Canal',
                'placeholder' => 'Selecione o canal',
                'choice_label' => fn (CanalContato $c) => $c->label(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('resultado', EnumType::class, [
                'class' => ResultadoContato::class,
                'label' => 'Resultado',
                'choice_label' => fn (ResultadoContato $r) => $r->label(),
                'attr' => ['class' => 'form-select'],
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
