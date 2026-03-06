<?php

namespace App\Form;

use App\Entity\Cliente\ClientePF;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClientePFType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // NUP
            ->add('nup', TextType::class, [
                'label' => 'NUP',
                'required' => false,
            ])
            // Dados pessoais
            ->add('nomeCompleto', TextType::class, [
                'label' => 'Nome Completo',
            ])
            ->add('cpf', TextType::class, [
                'label' => 'CPF',
            ])
            ->add('dataNascimento', DateType::class, [
                'label' => 'Data de Nascimento',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('estadoCivil', ChoiceType::class, [
                'label' => 'Estado Civil',
                'choices' => [
                    'Solteiro(a)' => 'SOLTEIRO',
                    'Casado(a)' => 'CASADO',
                    'Divorciado(a)' => 'DIVORCIADO',
                    'Viúvo(a)' => 'VIUVO',
                    'União estável' => 'UNIAO_ESTAVEL',
                ],
                'required' => false,
            ])
            ->add('profissao', TextType::class, [
                'label' => 'Profissão',
                'required' => false,
            ])

            // RG
            ->add('rg', TextType::class, [
                'label' => 'RG',
            ])
            ->add('rgOrgaoExpedidor', TextType::class, [
                'label' => 'Órgão Expedidor (RG)',
            ])
            ->add('rgDataEmissao', DateType::class, [
                'label' => 'Data de Emissão (RG)',
                'widget' => 'single_text',
                'required' => false,
            ])

            // Contato
            ->add('telefoneCelular', TelType::class, [
                'label' => 'Celular',
                'required' => false,
            ])
            ->add('telefoneFixo', TelType::class, [
                'label' => 'Telefone Fixo',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
            ])

            // Endereço
            ->add('cep', TextType::class, [
                'label' => 'CEP',
            ])
            ->add('endereco', TextType::class, [
                'label' => 'Endereço',
            ])
            ->add('complemento', TextType::class, [
                'label' => 'Complemento',
                'required' => false,
            ])
            ->add('cidade', TextType::class, [
                'label' => 'Cidade',
            ])
            ->add('estado', TextType::class, [
                'label' => 'Estado (UF)',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClientePF::class,
        ]);
    }
}
