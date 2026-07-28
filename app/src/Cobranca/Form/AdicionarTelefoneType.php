<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\AdicionarTelefonePessoaInput;
use App\Cobranca\Enum\TipoTelefone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Mini-form "adicionar telefone" da ficha da pessoa (spec de qualificação §4/§7). `pessoaId` é campo
 * oculto preenchido pelo controller a partir da rota. A regra de "primeiro item nasce atual" e a
 * persistência ficam no `AdicionarTelefonePessoaUseCase` (não tocado por esta onda de UI).
 */
final class AdicionarTelefoneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('numero', TextType::class, [
            'label' => 'Telefone',
            // `inputmode="tel"` abre o teclado numérico no celular; `data-mascara="telefone"` é o gancho
            // do formatador de digitação (`telefone-mascara.js`), que age por delegação — o mesmo campo
            // continua funcionando quando a resposta AJAX troca o bloco inteiro no lugar.
            'attr' => [
                'class' => 'form-control',
                'maxlength' => 20,
                'inputmode' => 'tel',
                'data-mascara' => 'telefone',
                'placeholder' => '(00) 00000-0000',
            ],
        ]);

        // Radio (`expanded`) e não select: são duas opções, e a escolha tem de ser visível sem clique
        // extra. `Fixo` vem marcado por ser o comportamento de hoje — quem não mexer no campo continua
        // cadastrando o que já cadastrava.
        $builder->add('tipo', EnumType::class, [
            'class' => TipoTelefone::class,
            'label' => 'Tipo',
            'expanded' => true,
            'multiple' => false,
            'required' => false,
            'placeholder' => false,
            'choice_label' => static fn (TipoTelefone $tipo): string => $tipo->label(),
            'choice_attr' => static fn (TipoTelefone $tipo): array => ['data-tipo' => $tipo->value],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdicionarTelefonePessoaInput::class,
        ]);
    }
}
