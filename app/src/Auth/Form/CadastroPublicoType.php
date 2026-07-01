<?php

declare(strict_types=1);

namespace App\Auth\Form;

use App\Auth\DTO\IniciarCadastroPublicoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CadastroPublicoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomeCompleto', TextType::class, ['label' => 'Nome completo'])
            ->add('email', EmailType::class, ['label' => 'E-mail'])
            ->add('senha', PasswordType::class, ['label' => 'Senha'])
            ->add('oabNumero', TextType::class, ['label' => 'Número da OAB'])
            ->add('oabUf', TextType::class, ['label' => 'UF da OAB'])
            ->add('nomeEscritorio', TextType::class, ['label' => 'Nome do escritório'])
            ->add('aceiteTermos', CheckboxType::class, [
                'label'    => 'Li e aceito os Termos de Uso',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => IniciarCadastroPublicoInput::class,
        ]);
    }
}
