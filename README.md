# Forum

Simple PHP forum with:

- User registration and login
- First registered user automatically becomes `admin`
- `users.txt` as user database
- Category/thread/post storage in folders and `.txt` files
- Bootstrap + jQuery UI

## Run

From the project root:

```bash
php -S localhost:8000
```

Open `http://localhost:8000`.

## Storage

- Users: `users.txt`
- Forum data: `data/`
  - category folder
    - `category.json`
    - thread folder
      - `thread.json`
      - post files (`*.txt`)
