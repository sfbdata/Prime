<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\RegistrarLiquidacaoInput;
use App\Cobranca\Enum\TipoLiquidacao;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Registrar uma liquidação não monetária do caso (SPEC §11): bem móvel/imóvel ou outro. `casoId` vem da
 * rota. O que reduz o saldo é o valor RECONHECIDO; o valor ATRIBUÍDO ao bem é opcional e pode diferir —
 * os dois campos são independentes e nunca se força igualdade. Dinheiro em CENTAVOS via CentavosType.
 */
final class RegistrarLiquidacaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tipo', EnumType::class, [
                'class' => TipoLiquidacao::class,
                'label' => 'Tipo',
                'placeholder' => 'Selecione o tipo',
                'choice_label' => fn (TipoLiquidacao $t) => $t->label(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('descricaoBem', TextType::class, [
                'label' => 'Bem ou direito',
                'attr' => ['class' => 'form-control', 'maxlength' => 255, 'placeholder' => 'Ex.: Veículo Fiat Uno placa ABC-1234'],
            ])
            ->add('valorAtribuidoBem', CentavosType::class, [
                'label' => 'Valor atribuído ao bem (R$) — opcional',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('valorReconhecido', CentavosType::class, [
                'label' => 'Valor reconhecido da dívida (R$)',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('data', DateType::class, [
                'label' => 'Data da liquidação',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrarLiquidacaoInput::class,
        ]);
    }
}
