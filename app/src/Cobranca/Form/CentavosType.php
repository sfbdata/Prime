<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\Form\DataTransformer\CentavosParaReaisTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Campo de dinheiro da Gestão de Cobranças: o usuário digita reais em pt-BR ("1.234,56"), o modelo
 * recebe int em CENTAVOS (formato dos Input DTOs). Encapsula o CentavosParaReaisTransformer para que
 * nenhum Form precise repetir a conversão. Renderiza como TextType com prefixo R$ e teclado numérico.
 */
final class CentavosType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CentavosParaReaisTransformer());
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
