<?php

namespace App\Form;

use App\Entity\Cliente\ClientePJ;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClientePJType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Dados da empresa
            ->add('razaoSocial', TextType::class, [
                'label' => 'Razão Social',
            ])
            ->add('nomeFantasia', TextType::class, [
                'label' => 'Nome Fantasia',
                'required' => false,
            ])
            ->add('cnpj', TextType::class, [
                'label' => 'CNPJ',
            ])
            ->add('inscricaoEstadual', TextType::class, [
                'label' => 'Inscrição Estadual',
                'required' => false,
            ])
            ->add('inscricaoMunicipal', TextType::class, [
                'label' => 'Inscrição Municipal',
                'required' => false,
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
            ])
            ->add('enderecSede', TextType::class, [
                'label' => 'Endereço da Sede',
            ])

            // Representante Legal
            ->add('representanteLegal', TextType::class, [
                'label' => 'Nome do Representante Legal',
            ])
            ->add('representanteCpf', TextType::class, [
                'label' => 'CPF do Representante',
            ])
            ->add('representanteRg', TextType::class, [
                'label' => 'RG do Representante',
            ])
            ->add('representanteCargo', TextType::class, [
                'label' => 'Cargo do Representante',
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
            ]);

        $builder->add('contratoFile', FileType::class, [
            'label' => 'Arquivo do Contrato (PDF)',
            'mapped' => false,
            'required' => false,
            'constraints' => [
                new File([
                    'maxSize' => '5M',
                    'mimeTypes' => ['application/pdf', 'application/x-pdf'],
                    'mimeTypesMessage' => 'Por favor envie um PDF válido',
                ])
            ],
        ]);
        $builder->add('contratoDataInicio', DateType::class, [
            'label' => 'Data de Início do Contrato',
            'widget' => 'single_text',
            'mapped' => false,
            'required' => false,
        ]);
        $builder->add('contratoValorTotal', MoneyType::class, [
            'label' => 'Valor Total do Contrato',
            'currency' => 'BRL',
            'mapped' => false,
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClientePJ::class,
        ]);
    }
}
