<?php

declare(strict_types=1);

namespace App\Kanban\Form;

use App\Kanban\DTO\AtualizarBoardInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class KanbanBoardEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nome', TextType::class, [
                'label' => 'Nome do mural',
                'attr'  => ['maxlength' => 255],
            ])
            ->add('descricao', TextareaType::class, [
                'label'    => 'Descrição',
                'required' => false,
                'attr'     => ['rows' => 2, 'maxlength' => 2000],
            ])
            ->add('cor', TextType::class, [
                'label'    => 'Cor',
                'required' => false,
                'attr'     => ['maxlength' => 7],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'    => AtualizarBoardInput::class,
            'csrf_token_id' => 'kanban_board_edit',
        ]);
    }
}
