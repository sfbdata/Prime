<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Ponto\JornadaColaborador;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class JornadaColaboradorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('diasSemana', ChoiceType::class, [
                'label'    => 'Dias de trabalho',
                'choices'  => [
                    'Segunda-feira' => 1,
                    'Terça-feira'   => 2,
                    'Quarta-feira'  => 3,
                    'Quinta-feira'  => 4,
                    'Sexta-feira'   => 5,
                ],
                'multiple'  => true,
                'expanded'  => true,
                'required'  => true,
                'constraints' => [
                    new NotBlank(message: 'Selecione ao menos um dia de trabalho.'),
                ],
            ])
            ->add('entrada1', TimeType::class, [
                'label'        => 'Entrada (manhã)',
                'widget'       => 'single_text',
                'input'        => 'string',
                'input_format' => 'H:i',
                'required'     => true,
                'constraints'  => [new NotBlank(message: 'Informe o horário de entrada.')],
                'attr'         => ['class' => 'form-control'],
            ])
            ->add('saida1', TimeType::class, [
                'label'        => 'Início do intervalo',
                'widget'       => 'single_text',
                'input'        => 'string',
                'input_format' => 'H:i',
                'required'     => true,
                'constraints'  => [new NotBlank(message: 'Informe o horário de início do intervalo.')],
                'attr'         => ['class' => 'form-control'],
            ])
            ->add('entrada2', TimeType::class, [
                'label'        => 'Retorno do intervalo',
                'widget'       => 'single_text',
                'input'        => 'string',
                'input_format' => 'H:i',
                'required'     => true,
                'constraints'  => [new NotBlank(message: 'Informe o horário de retorno do intervalo.')],
                'attr'         => ['class' => 'form-control'],
            ])
            ->add('saida2', TimeType::class, [
                'label'        => 'Saída (tarde)',
                'widget'       => 'single_text',
                'input'        => 'string',
                'input_format' => 'H:i',
                'required'     => true,
                'constraints'  => [new NotBlank(message: 'Informe o horário de saída.')],
                'attr'         => ['class' => 'form-control'],
            ])
            ->add('sabadoAtivado', CheckboxType::class, [
                'label'    => 'Trabalha aos sábados',
                'mapped'   => false,
                'required' => false,
            ])
            ->add('entradaSabado', TimeType::class, [
                'label'        => 'Entrada (sábado)',
                'widget'       => 'single_text',
                'input'        => 'string',
                'input_format' => 'H:i',
                'required'     => false,
                'attr'         => ['class' => 'form-control'],
            ])
            ->add('saidaSabado', TimeType::class, [
                'label'        => 'Saída (sábado)',
                'widget'       => 'single_text',
                'input'        => 'string',
                'input_format' => 'H:i',
                'required'     => false,
                'attr'         => ['class' => 'form-control'],
            ])
            ->add('alertaHabilitado', CheckboxType::class, [
                'label'    => 'Ativar alerta de registro de ponto',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => JornadaColaborador::class,
        ]);
    }
}
