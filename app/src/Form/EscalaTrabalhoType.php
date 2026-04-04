<?php

namespace App\Form;

use App\Entity\Ponto\EscalaTrabalho;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EscalaTrabalhoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('entrada1', TextType::class, [
                'label' => 'Entrada (manhã)',
                'attr'  => ['placeholder' => '09:00', 'pattern' => '[0-2][0-9]:[0-5][0-9]'],
            ])
            ->add('saida1', TextType::class, [
                'label' => 'Repouso',
                'attr'  => ['placeholder' => '12:00', 'pattern' => '[0-2][0-9]:[0-5][0-9]'],
            ])
            ->add('entrada2', TextType::class, [
                'label' => 'Retorno',
                'attr'  => ['placeholder' => '13:00', 'pattern' => '[0-2][0-9]:[0-5][0-9]'],
            ])
            ->add('saida2', TextType::class, [
                'label' => 'Saída (tarde)',
                'attr'  => ['placeholder' => '18:00', 'pattern' => '[0-2][0-9]:[0-5][0-9]'],
            ])
            ->add('sabadoAtivado', CheckboxType::class, [
                'label'    => 'Trabalha aos sábados',
                'mapped'   => false,
                'required' => false,
            ])
            ->add('entradaSabado', TextType::class, [
                'label'    => 'Entrada (sábado)',
                'required' => false,
                'attr'     => ['placeholder' => '09:00', 'pattern' => '[0-2][0-9]:[0-5][0-9]'],
            ])
            ->add('saidaSabado', TextType::class, [
                'label'    => 'Saída (sábado)',
                'required' => false,
                'attr'     => ['placeholder' => '13:00', 'pattern' => '[0-2][0-9]:[0-5][0-9]'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EscalaTrabalho::class,
        ]);
    }
}
