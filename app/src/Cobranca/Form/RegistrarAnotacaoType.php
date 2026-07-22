<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\RegistrarAnotacaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Anotação livre na linha do tempo do Caso (ajuste 2026-07). Um campo só, exibido inline no topo da
 * aba Histórico — não é modal: escrever uma observação é trabalho de segundos e não merece dois
 * cliques. Validação (obrigatório, 5000 caracteres) nos #[Assert] do DTO. CSRF pelo token padrão.
 */
final class RegistrarAnotacaoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('texto', TextareaType::class, [
            'label' => false,
            'required' => false, // a obrigatoriedade é do DTO — evita o balão nativo do browser
            'attr' => [
                'rows' => 2,
                'maxlength' => 5000,
                'class' => 'form-control form-control-sm',
                'placeholder' => 'Anotar o que foi combinado, o que o devedor disse, o contexto do caso…',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrarAnotacaoInput::class,
        ]);
    }
}
