<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\Form\DataTransformer\PercentualParaTextoTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Campo de percentual da Gestão de Cobranças: o usuário digita em pt-BR ("10,50"), o modelo recebe a
 * string em formato "ponto" ("10.50") que casa com a coluna decimal(5,2) e os #[Assert] do DTO.
 * Encapsula o PercentualParaTextoTransformer para nenhum Form repetir a conversão. Renderiza como
 * TextType com teclado numérico; o sufixo "%" (input-group) fica no template.
 */
final class PercentualType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new PercentualParaTextoTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => ['inputmode' => 'decimal', 'placeholder' => '10,00'],
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
