import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';
import Underline from '@tiptap/extension-underline';

function registerTiptapEditor() {
    Alpine.data('tiptapEditor', (config = {}) => ({
        editor: null,
        content: config.content || '',
        placeholder: config.placeholder || 'Write something...',
        minimal: config.minimal || false,

        init() {
            // Prevent double-init from Livewire morphing
            if (this.editor) {
                this.editor.destroy();
                this.editor = null;
            }

            this.editor = new Editor({
                element: this.$refs.editor,
                extensions: [
                    StarterKit.configure({
                        heading: this.minimal ? false : { levels: [1, 2, 3] },
                    }),
                    Underline,
                    Link.configure({
                        openOnClick: false,
                        HTMLAttributes: { class: 'text-blue-600 underline dark:text-blue-400' },
                    }),
                    Image.configure({ inline: false }),
                    Placeholder.configure({ placeholder: this.placeholder }),
                ],
                content: this.content,
                editorProps: {
                    attributes: {
                        class: 'prose prose-sm dark:prose-invert max-w-none focus:outline-none min-h-[120px] px-3 py-2',
                    },
                },
                onUpdate: ({ editor }) => {
                    this.content = editor.getHTML();
                    this.$dispatch('tiptap-update', { content: this.content });
                },
            });

            // Watch for external content changes
            this.$watch('content', (value) => {
                if (this.editor && this.editor.getHTML() !== value) {
                    this.editor.commands.setContent(value, false);
                }
            });
        },

        // Toolbar actions
        toggleBold() { this.editor?.chain().focus().toggleBold().run(); },
        toggleItalic() { this.editor?.chain().focus().toggleItalic().run(); },
        toggleUnderline() { this.editor?.chain().focus().toggleUnderline().run(); },
        toggleStrike() { this.editor?.chain().focus().toggleStrike().run(); },
        toggleBulletList() { this.editor?.chain().focus().toggleBulletList().run(); },
        toggleOrderedList() { this.editor?.chain().focus().toggleOrderedList().run(); },
        toggleBlockquote() { this.editor?.chain().focus().toggleBlockquote().run(); },
        setHeading(level) { this.editor?.chain().focus().toggleHeading({ level }).run(); },
        setParagraph() { this.editor?.chain().focus().setParagraph().run(); },
        undo() { this.editor?.chain().focus().undo().run(); },
        redo() { this.editor?.chain().focus().redo().run(); },

        setLink() {
            const url = prompt('Enter URL');
            if (url) {
                this.editor?.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
            }
        },

        removeLink() {
            this.editor?.chain().focus().unsetLink().run();
        },

        insertImage() {
            const url = prompt('Enter image URL');
            if (url) {
                this.editor?.chain().focus().setImage({ src: url }).run();
            }
        },

        // State checkers for active button styling
        isActive(type, attrs = {}) {
            return this.editor?.isActive(type, attrs) ?? false;
        },

        destroy() {
            this.editor?.destroy();
        },
    }));
}

// Register immediately if Alpine is already available (late dynamic import)
if (window.Alpine) {
    registerTiptapEditor();
} else {
    document.addEventListener('alpine:init', registerTiptapEditor);
}
