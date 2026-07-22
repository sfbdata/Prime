<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\AdicionarEnderecoPessoaInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Mini-form "adicionar endereço" da ficha da pessoa (spec de qualificação §4/§7). `pessoaId` é campo
 * oculto preenchido pelo controller a partir da rota. A regra de "primeiro item nasce atual" e a
 * persistência ficam no `AdicionarEnderecoPessoaUseCase` (não tocado por esta onda de UI).
 */
final class AdicionarEnderecoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('logradouro', TextType::class, [
                'label' => 'Logradouro',
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('numero', TextType::class, [
                'label' => 'Número',
                'attr' => ['class' => 'form-control', 'maxlength' => 20],
            ])
            ->add('complemento', TextType::class, [
                'label' => 'Complemento',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 120],
            ])
            ->add('bairro', TextType::class, [
                'label' => 'Bairro',
                'attr' => ['class' => 'form-control', 'maxlength' => 120],
            ])
            ->add('cidade', TextType::class, [
                'label' => 'Cidade',
                'attr' => ['class' => 'form-control', 'maxlength' => 120],
            ])
            ->add('uf', TextType::class, [
                'label' => 'UF',
                'attr' => ['class' => 'form-control', 'maxlength' => 2],
            ])
            ->add('cep', TextType::class, [
                'label' => 'CEP',
                'attr' => ['class' => 'form-control', 'maxlength' => 9],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdicionarEnderecoPessoaInput::class,
        ]);
    }
}
