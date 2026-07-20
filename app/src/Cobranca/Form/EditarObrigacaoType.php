<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\EditarObrigacaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Editar (corrigir) uma obrigação. `obrigacaoId` NÃO é campo — vem da rota. Espelha o form de registro +
 * os encargos SEPARADOS (juros/multa/correção — F4; antes era o agregado `encargosReconhecidos`, da
 * unificação do "Reconhecer valor" do ajuste 5) + `motivo` obrigatório. O modal é reutilizável (1 por
 * página) e pré-preenchido por JS via os `data-*` da linha da obrigação.
 *
 * Os campos de encargo são em R$ (CentavosType) e são a ÚNICA fonte de verdade submetida. O "%" ao lado
 * de cada um, no Twig, é auxiliar: não tem `name`, não chega ao servidor e por isso não precisa de
 * validação nem de reidratação no B5 — o JS o recalcula a partir do R$ quando o modal abre.
 *
 * `data-encargo` é o gancho que o JS usa para casar cada R$ com o seu % (travado por
 * ObjetoShowContratoJsTest). `empty_data => '0'` é string porque o transformer roda ANTES da conversão
 * para centavos; campo vazio vira 0, não null — nenhum encargo é obrigatório.
 */
final class EditarObrigacaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('descricao', TextType::class, [
                'label' => 'Descrição',
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('valorOriginal', CentavosType::class, [
                'label' => 'Valor original (R$)',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('vencimentoOriginal', DateType::class, [
                'label' => 'Vencimento',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('referenciaExterna', TextType::class, [
                'label' => 'Referência externa (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('juros', CentavosType::class, [
                'label' => 'Juros (R$)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control', 'data-encargo' => 'juros'],
            ])
            ->add('multa', CentavosType::class, [
                'label' => 'Multa (R$)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control', 'data-encargo' => 'multa'],
            ])
            ->add('correcao', CentavosType::class, [
                'label' => 'Correção (R$)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control', 'data-encargo' => 'correcao'],
            ])
            // Honorário (Ajuste 2): SEM `empty_data` — o transformer mapeia vazio para `null` (não `0`), e
            // `null` = automático (o motor recalcula), `0` = zero explícito (congela). O campo NÃO é
            // pré-preenchido pelo JS ao abrir (fica vazio = automático); incide sobre a base COMPOSTA no
            // espelho %↔R$ (data-encargo-base="composta" no Twig).
            ->add('honorarios', CentavosType::class, [
                'label' => 'Honorários (R$)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-encargo' => 'honorarios'],
            ])
            ->add('motivo', TextType::class, [
                'label' => 'Motivo da correção',
                'attr' => ['class' => 'form-control', 'maxlength' => 255, 'placeholder' => 'Ex.: valor digitado errado na importação'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditarObrigacaoInput::class,
        ]);
    }
}
