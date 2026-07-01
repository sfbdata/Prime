<?php

declare(strict_types=1);

namespace App\Tenant\Form;

use App\Tenant\DTO\CriarEscritorioInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CriarEscritorioType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('nome', TextType::class, [
            'label' => 'Nome do escritório',
        ]);

        // OAB só é pedida quando o usuário ainda não tem OAB cadastrada na conta.
        if ($options['exigir_oab'] === true) {
            $builder
                ->add('oabNumero', TextType::class, [
                    'label' => 'Número da OAB',
                ])
                ->add('oabUf', TextType::class, [
                    'label' => 'UF da OAB',
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CriarEscritorioInput::class,
            'exigir_oab' => true,
        ]);
        $resolver->setAllowedTypes('exigir_oab', 'bool');
    }
}
