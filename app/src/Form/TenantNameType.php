<?php

namespace App\Form;

use App\Entity\Tenant\Tenant;
use App\Validator\Cnpj;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TenantNameType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nome do Escritório',
                'attr'  => ['class' => 'form-control'],
            ])
            ->add('responsavelAssinatura', TextType::class, [
                'label'    => 'Responsável pela Assinatura',
                'required' => false,
                'attr'     => ['class' => 'form-control'],
            ])
            ->add('cnpj', TextType::class, [
                'label'       => 'CNPJ',
                'required'    => false,
                'constraints' => [new Cnpj()],
                'attr'        => [
                    'class'       => 'form-control',
                    'data-cnpj'   => 'true',
                    'maxlength'   => '18',
                    'placeholder' => '00.000.000/0000-00',
                ],
            ])
            ->add('cep', TextType::class, [
                'label'    => 'CEP',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-control',
                    'data-cep'    => 'true',
                    'maxlength'   => '9',
                    'placeholder' => '00000-000',
                ],
            ])
            ->add('logradouro', TextType::class, [
                'label'    => 'Logradouro',
                'required' => false,
                'attr'     => ['class' => 'form-control'],
            ])
            ->add('numero', TextType::class, [
                'label'    => 'Número',
                'required' => false,
                'attr'     => ['class' => 'form-control'],
            ])
            ->add('complemento', TextType::class, [
                'label'    => 'Complemento',
                'required' => false,
                'attr'     => ['class' => 'form-control'],
            ])
            ->add('bairro', TextType::class, [
                'label'    => 'Bairro',
                'required' => false,
                'attr'     => ['class' => 'form-control'],
            ])
            ->add('cidade', TextType::class, [
                'label'    => 'Cidade',
                'required' => false,
                'attr'     => ['class' => 'form-control'],
            ])
            ->add('estado', ChoiceType::class, [
                'label'       => 'Estado (UF)',
                'required'    => false,
                'placeholder' => 'Selecione',
                'attr'        => ['class' => 'form-select'],
                'choices'     => [
                    'AC' => 'AC', 'AL' => 'AL', 'AP' => 'AP', 'AM' => 'AM',
                    'BA' => 'BA', 'CE' => 'CE', 'DF' => 'DF', 'ES' => 'ES',
                    'GO' => 'GO', 'MA' => 'MA', 'MT' => 'MT', 'MS' => 'MS',
                    'MG' => 'MG', 'PA' => 'PA', 'PB' => 'PB', 'PR' => 'PR',
                    'PE' => 'PE', 'PI' => 'PI', 'RJ' => 'RJ', 'RN' => 'RN',
                    'RS' => 'RS', 'RO' => 'RO', 'RR' => 'RR', 'SC' => 'SC',
                    'SP' => 'SP', 'SE' => 'SE', 'TO' => 'TO',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tenant::class,
        ]);
    }
}
