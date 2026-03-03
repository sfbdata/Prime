<?php

namespace App\Form;

use App\Entity\Agenda\Evento;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EventoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, [
                'label' => 'Título',
                'attr' => ['placeholder' => 'Título do evento'],
            ])
            ->add('descricao', TextareaType::class, [
                'label' => 'Descrição',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Descrição do evento (opcional)'],
            ])
            ->add('dataInicio', DateTimeType::class, [
                'label' => 'Data/Hora Início',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('duracao', NumberType::class, [
                'label' => 'Duração (horas)',
                'mapped' => false,
                'html5' => true,
                'attr' => [
                    'min' => 0.5,
                    'max' => 24,
                    'step' => 0.5,
                    'placeholder' => 'Ex: 1, 2, 0.5',
                ],
                'data' => $options['duracao_inicial'],
            ])
            ->add('local', TextType::class, [
                'label' => 'Local',
                'required' => false,
                'attr' => ['placeholder' => 'Local do evento (físico ou virtual)'],
            ])
            ->add('diaInteiro', CheckboxType::class, [
                'label' => 'Dia inteiro',
                'required' => false,
            ])
            ->add('cor', ChoiceType::class, [
                'label' => 'Cor',
                'choices' => [
                    'Azul' => Evento::COR_AZUL,
                    'Verde' => Evento::COR_VERDE,
                    'Amarelo' => Evento::COR_AMARELO,
                    'Vermelho' => Evento::COR_VERMELHO,
                    'Roxo' => Evento::COR_ROXO,
                    'Ciano' => Evento::COR_CIANO,
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Agendado' => Evento::STATUS_AGENDADO,
                    'Concluído' => Evento::STATUS_CONCLUIDO,
                    'Cancelado' => Evento::STATUS_CANCELADO,
                ],
            ])
            ->add('participantes', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'fullName',
                'label' => 'Participantes',
                'multiple' => true,
                'required' => false,
                'query_builder' => function (UserRepository $repo) {
                    return $repo->createQueryBuilder('u')
                        ->where('u.isActive = :active')
                        ->setParameter('active', true)
                        ->orderBy('u.fullName', 'ASC');
                },
                'attr' => ['class' => 'select2-participantes'],
            ])
            ->add('recorrente', CheckboxType::class, [
                'label' => 'Evento recorrente',
                'required' => false,
            ])
            ->add('tipoRecorrencia', ChoiceType::class, [
                'label' => 'Repetir',
                'required' => false,
                'choices' => [
                    'Diariamente' => 'diario',
                    'Semanalmente' => 'semanal',
                    'Mensalmente' => 'mensal',
                    'Anualmente' => 'anual',
                ],
                'placeholder' => 'Selecione...',
            ])
            ->add('fimRecorrencia', DateTimeType::class, [
                'label' => 'Repetir até',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Evento::class,
            'duracao_inicial' => 1,
        ]);
    }
}
