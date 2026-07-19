<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\Form\DataTransformer\TaxaBpParaTextoTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Campo de taxa de encargo da Gestão de Cobranças: o usuário digita o percentual em pt-BR ("1,00"),
 * o modelo recebe o int em BASIS POINTS (100 bp = 1%) das colunas de config de encargos. Encapsula
 * o TaxaBpParaTextoTransformer para nenhum Form repetir a conversão. Renderiza como TextType com
 * teclado numérico; o sufixo "%" (input-group) fica no template — mesmo desenho do PercentualType.
 */
final class TaxaBpType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new TaxaBpParaTextoTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => ['inputmode' => 'decimal', 'placeholder' => '0,00'],
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
