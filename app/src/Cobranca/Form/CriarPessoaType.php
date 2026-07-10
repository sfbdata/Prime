<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\CriarPessoaInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Cadastrar uma Pessoa no domínio de cobranças (SPEC §7/§24). CPF/CNPJ são opcionais. Validação nos
 * #[Assert] do DTO; normalização (trim; null se vazio) no UseCase.
 */
final class CriarPessoaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nome', TextType::class, [
                'label' => 'Nome',
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('cpf', TextType::class, [
                'label' => 'CPF (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 14],
            ])
            ->add('cnpj', TextType::class, [
                'label' => 'CNPJ (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 18],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('telefone', TextType::class, [
                'label' => 'Telefone (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 20],
            ])
            ->add('observacao', TextareaType::class, [
                'label' => 'Observação (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CriarPessoaInput::class,
        ]);
    }
}
