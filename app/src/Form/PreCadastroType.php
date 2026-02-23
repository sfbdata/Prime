<?php

namespace App\Form;

use App\Entity\Comercial\PreCadastro;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PreCadastroType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomeCliente', TextType::class, [
                'label' => 'Nome do Cliente',
            ])
            ->add('cpf', TextType::class, [
                'label' => 'CPF/CNPJ',
            ])
            ->add('tipo', ChoiceType::class, [
                'label' => 'Tipo',
                'choices' => [
                    'Pessoa Jurídica - Empresa' => 'PJ_EMPRESA',
                    'Pessoa Física' => 'PF',
                    'Pessoa Jurídica - Condomínio/Associação' => 'PJ_CONDOMINIO',
                ],
            ])
            ->add('telefone', TelType::class, [
                'label' => 'Telefone',
                'required' => false,
            ])
            ->add('areaDireito', TextType::class, [
                'label' => 'Área do Direito',
            ])
            ->add('prazo', DateType::class, [
                'label' => 'Prazo',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('natureza', ChoiceType::class, [
                'label' => 'Natureza',
                'choices' => [
                    'Consultivo' => 'CONSULTIVO',
                    'Judicial' => 'JUDICIAL',
                ],
            ])
            ->add('faseJudicial', ChoiceType::class, [
                'label' => 'Fase Judicial',
                'choices' => [
                    'Inicial' => 'INICIAL',
                    'Contestação' => 'CONTESTACAO',
                    'Recurso' => 'RECURSO',
                ],
                'required' => false,
            ])
            ->add('numeroProcesso', TextType::class, [
                'label' => 'Número do Processo',
                'required' => false,
            ])
            ->add('numeroContrato', TextType::class, [
                'label' => 'Número do Contrato',
                'required' => false,
            ])
            ->add('descricaoContrato', TextareaType::class, [
                'label' => 'Descrição do Contrato',
                'required' => false,
            ])
            ->add('valorContrato', MoneyType::class, [
                'label' => 'Valor do Contrato',
                'currency' => 'BRL',
                'required' => false,
            ])
            ->add('statusContrato', ChoiceType::class, [
                'label' => 'Status do Contrato',
                'choices' => [
                    'Pendente' => 'PENDENTE',
                    'Em Andamento' => 'EM_ANDAMENTO',
                    'Finalizado' => 'FINALIZADO',
                    'Cancelado' => 'CANCELADO',
                ],
                'required' => false,
                'placeholder' => 'Selecione um status',
            ]);

    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PreCadastro::class,
        ]);
    }
}
