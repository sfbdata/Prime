<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\AdicionarTelefonePessoaInput;
use Symfony\Component\Form\AbstractType;
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
            'attr' => ['class' => 'form-control', 'maxlength' => 20],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdicionarTelefonePessoaInput::class,
        ]);
    }
}
