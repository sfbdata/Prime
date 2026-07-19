<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\RegistrarObrigacaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Registrar uma obrigação (dívida) no Caso. `casoId` NÃO é campo — vem da rota e é preenchido pelo
 * controller. Dinheiro em CentavosType (int centavos no DTO); validação via #[Assert] do DTO.
 *
 * Encargos separados (F4, spec §11): juros/multa/correção OPCIONAIS já no lançamento, cada um com um "%"
 * auxiliar ao lado no Twig (sem `name`, só JS — o R$ é a fonte de verdade submetida). Deixar os três
 * vazios é o caso comum: a obrigação nasce zerada e o cron passa a calcular.
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrarObrigacaoInput::class,
        ]);
    }
}
