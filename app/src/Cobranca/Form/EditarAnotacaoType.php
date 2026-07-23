<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\EditarAnotacaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Correção do texto de uma anotação (ajuste 2026-07-22). Um campo só, exibido no modal compartilhado
 * da aba Histórico — o JS injeta a URL da linha clicada e o texto atual. Validação nos #[Assert] do
 * DTO; quem pode corrigir é decidido no servidor, nunca aqui.
 */
final class EditarAnotacaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('texto', TextareaType::class, [
            'label' => false,
            'required' => false, // a obrigatoriedade é do DTO — evita o balão nativo do browser
            'attr' => [
                // Liga o editor rico (barra de formatação) — ver public/js/editor-rico.js.
                'data-editor-rico' => true,
                'rows' => 4,
                'maxlength' => 5000,
                'class' => 'form-control',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditarAnotacaoInput::class,
        ]);
    }
}
