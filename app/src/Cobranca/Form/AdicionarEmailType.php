<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\AdicionarEmailPessoaInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Mini-form "adicionar e-mail" da ficha da pessoa (spec de qualificação §4/§7). `pessoaId` é campo
 * oculto preenchido pelo controller a partir da rota. A regra de "primeiro item nasce atual" e a
 * persistência ficam no `AdicionarEmailPessoaUseCase` (não tocado por esta onda de UI).
 */
final class AdicionarEmailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'E-mail',
            'attr' => ['class' => 'form-control', 'maxlength' => 255],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdicionarEmailPessoaInput::class,
        ]);
    }
}
