<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\RegistrarObrigacaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Registrar uma obrigação (dívida) no Caso. `casoId` NÃO é campo — vem da rota e é preenchido pelo
 * controller. Dinheiro em CentavosType (int centavos no DTO); validação via #[Assert] do DTO.
 *
 * Taxa por-obrigação (spec taxa-por-obrigacao): cada encargo (juros/multa/correção/honorários) tem um
 * trio `modo` (HiddenType, setado pelo JS ao editar % ou R$) + `Bp` (TaxaBpType, o %) + `Reais`
 * (CentavosType, o R$) — o espelho %↔R$ é só JS (preview); o servidor recebe os três e o
 * ConversorTaxaEncargo decide o override a partir do `modo`. `empty_data => 'herda'` no modo: a
 * obrigação nasce sem override (usa a taxa do caso) quando o JS não altera o hidden.
 */
final class RegistrarObrigacaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('descricao', TextType::class, [
                'label' => 'Descrição',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: Boleto 03/2026', 'maxlength' => 255],
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
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: nº do boleto', 'maxlength' => 255],
            ])
            ->add('modoJuros', HiddenType::class, ['empty_data' => 'herda'])
            ->add('jurosBp', TaxaBpType::class, [
                'label' => 'Juros ao mês (%)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-taxa' => 'juros', 'data-modo-target' => 'modoJuros'],
            ])
            ->add('jurosReais', CentavosType::class, [
                'label' => 'Juros (R$)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-taxa-reais' => 'juros'],
            ])
            ->add('modoMulta', HiddenType::class, ['empty_data' => 'herda'])
            ->add('multaBp', TaxaBpType::class, [
                'label' => 'Multa (%)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-taxa' => 'multa', 'data-modo-target' => 'modoMulta'],
            ])
            ->add('multaReais', CentavosType::class, [
                'label' => 'Multa (R$)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-taxa-reais' => 'multa'],
            ])
            ->add('modoCorrecao', HiddenType::class, ['empty_data' => 'herda'])
            ->add('correcaoBp', TaxaBpType::class, [
                'label' => 'Correção monetária (%)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-taxa' => 'correcao', 'data-modo-target' => 'modoCorrecao'],
            ])
            ->add('correcaoReais', CentavosType::class, [
                'label' => 'Correção (R$)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-taxa-reais' => 'correcao'],
            ])
            ->add('modoHonorarios', HiddenType::class, ['empty_data' => 'herda'])
            ->add('honorariosBp', TaxaBpType::class, [
                'label' => 'Honorários (%)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-taxa' => 'honorarios', 'data-modo-target' => 'modoHonorarios'],
            ])
            ->add('honorariosReais', CentavosType::class, [
                'label' => 'Honorários (R$)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-taxa-reais' => 'honorarios'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrarObrigacaoInput::class,
        ]);
    }
}
