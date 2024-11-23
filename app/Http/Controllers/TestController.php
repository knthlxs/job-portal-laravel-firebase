<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TestController extends Controller
{
    private $database;

    public function __construct()
    {
        $this->database = \App\Services\FirebaseService::connect();
    }

    // Method to show all blogs
    public function index()
    {
        return response()->json($this->database->getReference('test/blogs')->getValue());
    }

    // Method to create a new blog post
    public function create(Request $request)
    {
        $newBlogRef = $this->database
            ->getReference('test/blogs')
            ->push(); // Generate a new auto ID

        $newBlogRef->set([
            'title' => $request['title'],
            'content' => $request['content']
        ]);

        return response()->json('Blog has been created with ID: ' . $newBlogRef->getKey());
    }

    // Method to update an existing blog post
    public function edit(Request $request, $id)
    {
        $this->database
            ->getReference('test/blogs/' . $id) // Use the ID to locate the document
            ->update([
                'title' => $request['title'],    // Update the title
                'content' => $request['content'] // Update the content
            ]);

        return response()->json('Blog has been updated');
    }

    // Method to delete a blog post
    public function delete($id)
    {
        $this->database
            ->getReference('test/blogs/' . $id) // Use the ID to locate the document
            ->remove(); // Delete the document

        return response()->json('Blog has been deleted');
    }

    // Method to show a specific blog post by ID
    public function show($id)
    {
        $blog = $this->database
            ->getReference('test/blogs/' . $id) // Use the ID to locate the document
            ->getValue();

        if ($blog) {
            return response()->json($blog);
        } else {
            return response()->json(['message' => 'Blog not found'], 404);
        }
    }
}
