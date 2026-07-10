<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\JudicializarCasoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Judicializar um caso (SPEC §16): vincula uma Pasta judicial EXISTENTE do tenant. `casoId` vem da
 * rota; a Pasta é escolhida entre as do escritório (opção `pastas` = mapa label→id, montada pelo
 * controller — idêntica no render e no POST para o ChoiceType casar). Exige o módulo `pastas`.
 */
final class JudicializarCasoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('pastaId', ChoiceType::class, [
            'label' => 'Pasta judicial',
            'choices' => $options['pastas'],
            'placeholder' => 'Selecione a pasta',
            'choice_translation_domain' => false,
            'attr' => ['class' => 'form-select'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => JudicializarCasoInput::class,
            'pastas' => [],
        ]);
        $resolver->setAllowedTypes('pastas', 'array');
    }
}
