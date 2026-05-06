<?php

declare(strict_types=1);

namespace App\Kanban\Form;

use App\Kanban\DTO\CriarCardInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class KanbanCardType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, [
                'label' => 'Título do card',
                'attr'  => ['maxlength' => 500, 'placeholder' => 'Título da tarefa...', 'autofocus' => true],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'    => CriarCardInput::class,
            'csrf_token_id' => 'kanban_card',
        ]);
    }
}
