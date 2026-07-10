<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\AlterarPessoaCobradaInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Trocar manualmente a pessoa cobrada de um caso (SPEC §8). `casoId` vem da rota; a nova pessoa é
 * escolhida entre as do tenant (opção `pessoas` = mapa nome→id, montada pelo controller idêntica no
 * render e no POST). O motivo é obrigatório (fica no histórico).
 */
final class AlterarPessoaCobradaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('novaPessoaCobradaId', ChoiceType::class, [
                'label' => 'Nova pessoa cobrada',
                'choices' => $options['pessoas'],
                'placeholder' => 'Selecione a pessoa',
                'choice_translation_domain' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('motivo', TextType::class, [
                'label' => 'Motivo da alteração',
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AlterarPessoaCobradaInput::class,
            'pessoas' => [],
        ]);
        $resolver->setAllowedTypes('pessoas', 'array');
    }
}
