<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Vincular uma Pessoa a um Objeto de Cobrança (SPEC §7). `objetoId` NÃO é campo — vem da rota e é
 * preenchido pelo controller. As opções de pessoa (`pessoas` = mapa nome→id) são escopadas ao tenant e
 * passadas pelo controller, idênticas no render e no POST. Validação nos #[Assert] do DTO.
 */
final class VincularPessoaAObjetoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('pessoaId', ChoiceType::class, [
                'label' => 'Pessoa',
                'choices' => $options['pessoas'],
                'placeholder' => 'Selecione a pessoa…',
                'choice_translation_domain' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('tipoVinculo', EnumType::class, [
                'label' => 'Tipo de vínculo',
                'class' => TipoVinculo::class,
                'choice_label' => static fn (TipoVinculo $t): string => $t->label(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('dataInicio', DateType::class, [
                'label' => 'Início (opcional)',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['class' => 'form-control'],
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
            'data_class' => VincularPessoaAObjetoInput::class,
            'pessoas' => [],
        ]);
        $resolver->setAllowedTypes('pessoas', 'array');
    }
}
