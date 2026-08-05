<?php

declare(strict_types=1);

namespace App\Auth\Form;

use App\Auth\DTO\RedefinirSenhaInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RedefinirSenhaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('senha', RepeatedType::class, [
            'type'            => PasswordType::class,
            'first_options'   => ['label' => 'Nova senha'],
            'second_options'  => ['label' => 'Repita a nova senha'],
            'invalid_message' => 'As duas senhas não conferem.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RedefinirSenhaInput::class,
        ]);
    }
}
