<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\CriarCarteiraInput;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Criar uma Carteira de Cobrança para um Cliente credor do escritório (SPEC §4). O credor é escolhido
 * num ChoiceType cujas opções (`clientes` = mapa nome→id) são escopadas ao tenant e passadas pelo
 * controller — idênticas no render e no POST, para a validação do ChoiceType casar. Validação nos
 * #[Assert] do DTO.
 */
final class CriarCarteiraType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nome', TextType::class, [
                'label' => 'Nome da carteira',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: Condomínio Edifício Central', 'maxlength' => 255],
            ])
            ->add('clienteId', ChoiceType::class, [
                'label' => 'Cliente credor',
                'choices' => $options['clientes'],
                'placeholder' => 'Selecione o credor…',
                'choice_translation_domain' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('modo', EnumType::class, [
                'label' => 'Modo de operação',
                'class' => ModoCarteira::class,
                'choice_label' => static fn (ModoCarteira $m): string => $m->label(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('formaHonorarios', EnumType::class, [
                'label' => 'Forma de honorários',
                'class' => FormaHonorarios::class,
                'choice_label' => static fn (FormaHonorarios $f): string => $f->label(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('percentualHonorarios', TextType::class, [
                'label' => 'Percentual de honorários (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: 10.00'],
            ])
            ->add('toleranciaAtrasoDias', IntegerType::class, [
                'label' => 'Tolerância de atraso (dias)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control', 'min' => 0],
            ])
            ->add('tipoVinculoPreferido', EnumType::class, [
                'label' => 'Vínculo preferido (opcional)',
                'class' => TipoVinculo::class,
                'choice_label' => static fn (TipoVinculo $t): string => $t->label(),
                'required' => false,
                'placeholder' => '—',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('rotuloObjeto', TextType::class, [
                'label' => 'Rótulo do objeto (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: Unidade, Veículo', 'maxlength' => 50],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CriarCarteiraInput::class,
            'clientes' => [],
        ]);
        $resolver->setAllowedTypes('clientes', 'array');
    }
}
