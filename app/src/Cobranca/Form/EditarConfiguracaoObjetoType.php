<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\DTO\EditarConfiguracaoObjetoInput;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Form\DataTransformer\TaxaBpParaTextoTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Editar a CONFIGURAÇÃO DE ENCARGOS de um Objeto de Cobrança já existente (spec "cascata de encargos
 * ao vivo sem snapshot" §4, #9-T3) — o NÍVEL 2 (o "meio") da cascata `Carteira → Objeto → Obrigação`.
 * `objetoId` NÃO é campo — vem da rota e é preenchido pelo controller, que também pré-carrega o DTO
 * com os overrides atuais do objeto para o modal já abrir preenchido.
 *
 * TODOS os 10 campos são `required => false` e SEM `empty_data`: vazio = `null` = herda a carteira —
 * a mesma convenção dos overrides nullable do Caso (`EditarConfiguracaoCasoType::baseHonorarios`) e da
 * Obrigação (`EditarObrigacaoType`), e propositalmente DIFERENTE da carteira (nível 1, campos NOT NULL
 * com `empty_data`), que não tem para onde herdar.
 *
 * DESVIO da spec §4 (documentado no relatório da T3): só % (bp) via `TaxaBpType` — sem R$/
 * `ConversorTaxaEncargo`, que exige principal+vencimento únicos que o objeto (agregado de várias
 * obrigações) não tem.
 */
final class EditarConfiguracaoObjetoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // O modal MOSTRA o que a carteira tem configurado, em vez de anunciar herança: cada campo
        // vazio exibe, como placeholder, o valor que vale hoje. Placeholder e não valor preenchido —
        // a diferença é de dinheiro, não de estilo. Campo preenchido vira OVERRIDE do objeto no
        // submit, e a partir daí mudar a carteira não alcança mais este objeto; um "salvar" sem
        // intenção de mudar nada congelaria os 10 campos em silêncio.
        $daCarteira = $options['configCarteira'];
        $taxa = new TaxaBpParaTextoTransformer();
        $porcento = static fn (?int $bp): string => $daCarteira === null || $bp === null ? '' : $taxa->transform($bp);
        $dias = static fn (?int $d): string => $daCarteira === null || $d === null ? '' : (string) $d;
        $opcao = static fn (?string $rotulo): string => $daCarteira === null || $rotulo === null ? '—' : $rotulo;

        $builder
            ->add('taxaJurosMensalBp', TaxaBpType::class, [
                'label' => 'Juros ao mês (%)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => $porcento($daCarteira?->taxaJurosMensalBp)],
            ])
            // SEM `empty_data` nos selects de encargo — de propósito (mesma nota da carteira/caso):
            // omitir o campo no POST não pode reescrever um override existente por acidente.
            ->add('regimeJuros', EnumType::class, [
                'label' => 'Regime de juros',
                'class' => RegimeJuros::class,
                'choice_label' => static fn (RegimeJuros $r): string => $r->label(),
                'required' => false,
                'placeholder' => $opcao($daCarteira?->regimeJuros->label()),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('taxaMultaBp', TaxaBpType::class, [
                'label' => 'Multa (%)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => $porcento($daCarteira?->taxaMultaBp)],
            ])
            ->add('baseMulta', EnumType::class, [
                'label' => 'Base da multa',
                'class' => BaseEncargo::class,
                'choice_label' => static fn (BaseEncargo $b): string => $b->label(),
                'required' => false,
                'placeholder' => $opcao($daCarteira?->baseMulta->label()),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('taxaCorrecaoBp', TaxaBpType::class, [
                'label' => 'Correção monetária (%)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => $porcento($daCarteira?->taxaCorrecaoBp)],
            ])
            ->add('baseCorrecao', EnumType::class, [
                'label' => 'Base da correção',
                'class' => BaseEncargo::class,
                'choice_label' => static fn (BaseEncargo $b): string => $b->label(),
                'required' => false,
                'placeholder' => $opcao($daCarteira?->baseCorrecao->label()),
                'attr' => ['class' => 'form-select'],
            ])
            // Override da ALÍQUOTA de honorários deste objeto (supersede D2, nível 2): bp direto, não
            // forma+percentual — a forma continua só na carteira.
            ->add('taxaHonorariosBp', TaxaBpType::class, [
                'label' => 'Honorários (%)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => $porcento($daCarteira?->taxaHonorariosBp)],
            ])
            ->add('baseHonorarios', EnumType::class, [
                'label' => 'Base dos honorários',
                'class' => BaseEncargo::class,
                'choice_label' => static fn (BaseEncargo $b): string => $b->label(),
                'required' => false,
                'placeholder' => $opcao($daCarteira?->baseHonorarios->label()),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('carenciaHonorariosDias', IntegerType::class, [
                'label' => 'Carência dos honorários (dias)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => $dias($daCarteira?->carenciaHonorariosDias),
                ],
            ])
            ->add('toleranciaJurosMultaDias', IntegerType::class, [
                'label' => 'Carência de juros e multa (dias)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => $dias($daCarteira?->toleranciaJurosMultaDias),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditarConfiguracaoObjetoInput::class,
            // Config JÁ RESOLVIDA da carteira (nível 1), só para exibição nos placeholders. Null =
            // objeto órfão de carteira: aí não há valor de carteira a mostrar, e os campos ficam sem
            // pista em vez de exibir um zero que ninguém configurou.
            'configCarteira' => null,
        ]);
        $resolver->setAllowedTypes('configCarteira', [ConfigEncargos::class, 'null']);
    }
}
