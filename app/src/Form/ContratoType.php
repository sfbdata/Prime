<?php

namespace App\Form;

use App\Entity\Auth\User;
use App\Entity\Contrato\Contrato;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ContratoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, [
                'label' => 'Título do Contrato',
                'attr' => [
                    'placeholder' => 'Ex: Contrato de Prestação de Serviços',
                ],
            ])
            ->add('descricao', TextareaType::class, [
                'label' => 'Descrição',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'Descrição detalhada do contrato (opcional)',
                ],
            ])
            ->add('dataInicio', DateType::class, [
                'label' => 'Data de Início',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
            ])
            ->add('valorTotal', MoneyType::class, [
                'label' => 'Valor Total',
                'currency' => 'BRL',
                'required' => false,
            ])
            ->add('responsavel', EntityType::class, [
                'class' => User::class,
                'label' => 'Responsável',
                'required' => false,
                'placeholder' => 'Selecione um responsável...',
                'choice_label' => 'fullName',
                'attr' => [
                    'class' => 'form-select select2-responsavel',
                    'data-placeholder' => 'Pesquisar funcionário...',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contrato::class,
        ]);
    }
}
