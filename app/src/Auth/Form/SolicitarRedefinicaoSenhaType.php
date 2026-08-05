<?php

declare(strict_types=1);

namespace App\Auth\Form;

use App\Auth\DTO\SolicitarRedefinicaoSenhaInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SolicitarRedefinicaoSenhaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail da sua conta',
            ])
            // Honeypot: escondido no template, invisível para gente e irresistível para robô.
            // `empty_data` é obrigatório aqui: sem ele o campo em branco chega como null e
            // estoura no DTO, que tipa a propriedade como string.
            ->add('confirmacao', TextType::class, [
                'label'      => 'Não preencha este campo',
                'required'   => false,
                'empty_data' => '',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SolicitarRedefinicaoSenhaInput::class,
        ]);
    }
}
