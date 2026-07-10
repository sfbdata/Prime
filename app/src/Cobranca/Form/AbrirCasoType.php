<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\AbrirCasoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Abrir um Caso de Cobrança para um Objeto (SPEC §4/§6). `objetoId` NÃO é campo — vem da rota e é
 * preenchido pelo controller. A pessoa cobrada é escolhida num ChoiceType cujas opções (`pessoas` =
 * mapa nome→id) são escopadas ao tenant e passadas pelo controller, idênticas no render e no POST.
 * Validação nos #[Assert] do DTO.
 */
final class AbrirCasoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('pessoaCobradaId', ChoiceType::class, [
                'label' => 'Pessoa cobrada',
                'choices' => $options['pessoas'],
                'placeholder' => 'Selecione a pessoa cobrada…',
                'choice_translation_domain' => false,
                'attr' => ['class' => 'form-select'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AbrirCasoInput::class,
            'pessoas' => [],
        ]);
        $resolver->setAllowedTypes('pessoas', 'array');
    }
}
