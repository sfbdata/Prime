<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\EditarConfiguracaoCarteiraInput;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Editar a CONFIGURAÇÃO de uma Carteira já existente (SPEC §18): modo, honorários, tolerância, vínculo
 * preferido e rótulo do objeto. `carteiraId` NÃO é campo — vem da rota e é preenchido pelo controller,
 * que também pré-carrega o DTO com os valores atuais para o modal já abrir preenchido. Nome e credor
 * não são editáveis (invariável 4). Validação nos #[Assert] do DTO.
 */
final class EditarConfiguracaoCarteiraType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
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
            ->add('percentualHonorarios', PercentualType::class, [
                'label' => 'Percentual de honorários (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
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
            ])
            // Encargos por atraso (nível 1 da cascata): padrões para as NOVAS cobranças da carteira.
            ->add('taxaJurosMensalBp', TaxaBpType::class, [
                'label' => 'Juros ao mês (%)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control'],
            ])
            // empty_data = valor da VIEW (o `value` do enum): rede de segurança para POST que não
            // traz o select (o DTO tem a propriedade tipada não-nullable — null viraria TypeError).
            ->add('regimeJuros', EnumType::class, [
                'label' => 'Regime de juros',
                'class' => RegimeJuros::class,
                'choice_label' => static fn (RegimeJuros $r): string => $r->label(),
                'empty_data' => RegimeJuros::Simples->value,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('taxaMultaBp', TaxaBpType::class, [
                'label' => 'Multa (%)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('baseMulta', EnumType::class, [
                'label' => 'Base da multa',
                'class' => BaseEncargo::class,
                'choice_label' => static fn (BaseEncargo $b): string => $b->label(),
                'empty_data' => BaseEncargo::Principal->value,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('taxaCorrecaoBp', TaxaBpType::class, [
                'label' => 'Correção monetária (%)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('baseCorrecao', EnumType::class, [
                'label' => 'Base da correção',
                'class' => BaseEncargo::class,
                'choice_label' => static fn (BaseEncargo $b): string => $b->label(),
                'empty_data' => BaseEncargo::Principal->value,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('baseHonorarios', EnumType::class, [
                'label' => 'Base dos honorários',
                'class' => BaseEncargo::class,
                'choice_label' => static fn (BaseEncargo $b): string => $b->label(),
                'empty_data' => BaseEncargo::Composta->value,
                'attr' => ['class' => 'form-select'],
            ])
            // Vazio = null = herda a tolerância de atraso da carteira (não vira 0 por acidente).
            ->add('carenciaHonorariosDias', IntegerType::class, [
                'label' => 'Carência dos honorários (dias)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => 'Vazio = usa a tolerância de atraso',
                ],
            ])
            ->add('toleranciaJurosMultaDias', IntegerType::class, [
                'label' => 'Carência de juros e multa (dias)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control', 'min' => 0],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditarConfiguracaoCarteiraInput::class,
        ]);
    }
}
